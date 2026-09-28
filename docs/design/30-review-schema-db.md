<!-- Generated 2026-09-28 during PFPMS planning (Claude Code analysis). Secrets redacted. Line references are to the legacy-baseline tag. -->

**Schema & DB review: 18 problems found and 5 claims confirmed**

The biggest problems are #1, #2 and #3. I could not test any DDL: no mysqld/mariadbd is running and I did not start one, so engine behaviour below is from documented behaviour plus the repo's own dumps. XAMPP client reports 10.4.28-MariaDB. `sodium` is absent and `pdo_mysql` is present.

**Problems**

1. **Durable audit connection can hang on itself.** Claim (§3.2 `Db.php`, §3.4 step 2): "`durable()` (second autocommit connection for audit rows that must survive a rollback)".
   - Verdict: RISKY.
   - Evidence: `docs/PFPMS_schema_v2.sql:1060` has `fk_audit_log_session_id` → `user_session`. 0009 adds `audit_log.import_batch_id` with an FK.
   - If the main transaction has inserted the parent row but not committed it (the `user_session` at login or in the offline-session upsert, or the `import_batch`), the child insert on the second connection waits on that row's lock. PHP is waiting on the second connection, so it stalls for `innodb_lock_wait_timeout` (50 s) and fails with 1205. `Db::transaction` then retries 1205, so it happens again.
   - Correction: durable rows must never reference parents created in the open transaction. Write `session_id`/`import_batch_id` as NULL, or defer the write until after rollback/commit.

2. **The dump-diff test cannot pass.** Claim (§7 smoke tests): "`mysqldump --no-data` diff shows only JSON vs LONGTEXT".
   - Verdict: WRONG.
   - Evidence:
     - Percona dump `sql/foodpantrydb.sql:33` has `int NOT NULL`; MariaDB dump `sql/Old Versions/dbClient.sql:32` has `int(11)`.
     - `sql/foodpantrydb.sql:98` has `CURRENT_TIMESTAMP`; `sql/Old Versions/foodpantrydb9-1-26.sql:101` has `current_timestamp()`.
     - MariaDB also emits `CHECK (json_valid(..))`, `smallint(6)`/`tinyint(4)`, and different generated-column text (`_utf8mb4'Active'` on MySQL).
   - Correction: compare normalised `information_schema` (COLUMNS type, nullability, normalised defaults; STATISTICS; KEY_COLUMN_USAGE) instead of dump text.

3. **Retrying 1205/1213 is not safe as described.** Claim (§3.2): "`transaction()` supports nesting via savepoints and retries 1213/1205".
   - Verdict: RISKY.
   - A 1205 lock-wait timeout rolls back only the last statement (`innodb_rollback_on_timeout=OFF` by default on both engines), so earlier writes stay pending.
   - A 1213 deadlock rolls back the whole transaction and removes its savepoints; `ROLLBACK TO SAVEPOINT` then fails with 1305.
   - Correction: retry only at the outermost level, always after an explicit full ROLLBACK, and re-run the whole closure.

4. **SiteGround's database default collation is probably 0900.** Claim (D2/§5): the 520 collation is set per table.
   - Verdict: RISKY.
   - A database created in Site Tools on MySQL 8.4 most likely defaults to `utf8mb4_0900_ai_ci`. Anything created without an explicit COLLATE inherits it: `schema_version` (made by `migrate.php`), `CREATE TEMPORARY TABLE` in reports.
   - Joins against 520 columns then raise 1267 "Illegal mix of collations", and SchemaContractTest's "every table 520" fails on `schema_version`.
   - Correction: 0001 runs `ALTER DATABASE … COLLATE utf8mb4_unicode_520_ci` (or `migrate.php` aborts if `@@collation_database` differs); `schema_version` and temp tables use an explicit COLLATE; the contract test also checks the database default.

5. **The `VALUES(` guard is mis-specified and no portable upsert is named.** Claim (§7): CI guard fails on "`VALUES(` upserts"; §3.3 "INSERT…ON DUPLICATE upsert".
   - Verdict: RISKY.
   - A bare `VALUES(` pattern also matches every ordinary `INSERT … VALUES(`.
   - The ban itself is right: MySQL 8.4 warns 1287 on `VALUES()` in the UPDATE clause, and MariaDB 10.4 has no `AS new` row alias.
   - Correction:
     - Guard regex: `ON\s+DUPLICATE\s+KEY\s+UPDATE[^;]*\bVALUES\s*\(` plus `\)\s+AS\s+\w+\s+ON\s+DUPLICATE`.
     - Portable form: `… ON DUPLICATE KEY UPDATE quantity_on_hand = quantity_on_hand + ?`, binding the delta twice with positional `?`. With `EMULATE_PREPARES=false`, reusing a named placeholder gives HY093.

6. **The guard misses other MySQL-8-only SQL.** Claim (D2): "No MySQL-8-only syntax" is enforced by the guard list.
   - Verdict: RISKY (incomplete). Only `->>` is banned, but `->` is also unsupported on MariaDB.
   - Correction: add these, which MariaDB 10.4 lacks:
     - `->'$`
     - `JSON_TABLE` (MariaDB 10.6+)
     - `JSON_ARRAYAGG` / `JSON_OBJECTAGG` (10.5+)
     - `RENAME COLUMN` (10.5.2+)
     - `ANY_VALUE(`, `LATERAL`, functional index `((…))`, `CAST(… AS JSON)`, `UUID_TO_BIN`, `REGEXP_LIKE`

     And these, which MySQL 8.4 lacks:
     - `ADD/DROP … IF [NOT] EXISTS` on columns/indexes
     - `RETURNING`
     - `NEXTVAL` / `CREATE SEQUENCE`

     P7 report SQL is the likeliest place to break.

7. **"Strict sql_mode" and datetime handling are not portable as left.** Claim (D2): "a strict sql_mode".
   - Verdict: RISKY (unspecified).
   - `NO_AUTO_CREATE_USER` gives error 1231 on MySQL 8.4.
   - `TIME_TRUNCATE_FRACTIONAL` exists only on MySQL and `TIME_ROUND_FRACTIONAL` only on MariaDB.
   - Fractional seconds are rounded on MySQL and truncated on MariaDB. So a `distributed_at` of `23:59:59.6` becomes the next day on MySQL only.
   - MariaDB 10.4 rejects datetime literals with `+00:00`/`Z` in strict mode.
   - Correction:
     - Pin `STRICT_ALL_TABLES,ONLY_FULL_GROUP_BY,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION`.
     - PHP formats UTC values at exactly the column precision (`Y-m-d H:i:s` or `.v`) with no offset. Add this to the parity fixtures.

8. **Migrations are neither atomic nor safely re-runnable.** Claim (§5): "no `ADD COLUMN IF NOT EXISTS`"; `migrate` twice is a no-op.
   - Verdict: RISKY.
   - DDL commits implicitly, so a failure partway through 0003 cannot be re-run (1060 duplicate column).
   - `PDO::exec` of a multi-statement file reports only the first statement's error.
   - `CREATE TRIGGER` through a server-side prepare fails with 1295.
   - Correction: split into single statements run with `exec()`; record progress per statement, or check `information_schema` before each ALTER; one ALTER per table.

9. **Count-race offset cannot always be derived from the ledger.** Claim (R7): "`inventory_count.posted_at` … derived from existing ledger links".
   - Verdict: RISKY.
   - `inventory_count` has only `count_date DATE` (L580). A count line with zero delta writes no Count Adjustment row, so the offset check (P4) never sees it.
   - Correction: always write one ledger row per count line, even with qty 0, or add `inventory_count.posted_at DATETIME`.

10. **"One reversal per distribution" has no database guard.** Claim (P4).
    - Verdict: RISKY.
    - `reverses_distribution_id` (L480) has only the FK index (L998), so two concurrent reversals both succeed.
    - Correction: in 0004 add `UNIQUE KEY uk_distribution_reverses (reverses_distribution_id)`. Multiple NULLs are fine on both engines.

11. **Declined SNV offers cannot be stored as planned.** Claim (P5/0007): "a declined offer gets no number (0007), no reservation".
    - Verdict: RISKY.
    - The row still needs `snv_referral.expires_on DATE NOT NULL` (L671).
    - Correction: 0007 also makes `expires_on` NULL, or declines are recorded as a Re-offer followup plus an audit row with no referral row.

12. **Offline check-ins for the same household on two tablets collide.** Claim (R6/P3): the offline check-in handler.
    - Verdict: RISKY.
    - `uk_event_check_in_1 (event_id, participant_id)` (L454) raises 1062, and that case is not in R6's categories.
    - Correction: treat that 1062 as a merge that maps the item's `client_uuid` to the existing `check_in_id`; the dependent distribution then uses it.

13. **The `id_sequence` lock is held too long.** Claim (R10/0008): code allocated "inside the business transaction".
    - Verdict: RISKY.
    - It is one global row lock. An import commit of up to `import_max_rows_sync`=5000 rows in one transaction holds it throughout, so every registration and sync push that creates participants waits, then gets 1205.
    - Correction:
      - Allocate the code as the last statement before `INSERT participant`, after duplicate detection. `UPDATE id_sequence SET next_value=LAST_INSERT_ID(next_value+1)` works on both engines.
      - Refuse import commits while any event is Open.

14. **The optional-trigger fallback has four gaps.** Claim (9001, "Skipped if the host refuses").
    - Verdict: RISKY / UNVERIFIABLE.
    - With binary logging on and `log_bin_trust_function_creators=0`, `CREATE TRIGGER` needs SUPER, so SiteGround will likely end up Skipped. I cannot verify this locally. The fallback is sound only if:
      - (a) CI also runs without 9001; §7 runs only `--with-optional`.
      - (b) Dumps are handled. They embed `DEFINER=pfpms_owner`, so restoring to `pfpms_training` or staging fails with 1227. Use `--skip-triggers` and re-run 9001, or strip DEFINER.
      - (c) `@pfpms_allow_mutation` is reset in a `finally`. It is a session variable that stays set for the life of the connection.
      - (d) The retention purges are added to the allowlist. The `auth_audit_retention_months` purge DELETEs from `audit_log`, which is not allowlisted. `user_session` rows cannot be purged before their audit rows (L1060).

15. **Deploy backup will fail on MySQL 8.4 as written.** Claim (P1 Deploy): "mysqldump backup".
    - Verdict: RISKY.
    - MySQL 8.0.21+ mysqldump errors without the PROCESS privilege unless given `--no-tablespaces`, and shared-hosting users don't have PROCESS.
    - Correction: `--no-tablespaces --single-transaction` (plus `--triggers` if 9001 applied).

16. **`GET_LOCK` names are server-wide.** Claim (§3.2/§3.4): per-device and per-job `GET_LOCK`.
    - Verdict: RISKY.
    - On shared hosting, names can collide with other tenants and with `pfpms_training` on the same server.
    - Correction: prefix with `DATABASE()` and keep names ≤64 chars (MySQL errors above 64).

17. **SchemaContractTest counts and warnings check are off.** Claim (§5/§7): 66 tables; "exact expected totals after 0009"; "`SHOW WARNINGS` is empty".
    - Verdict: RISKY.
    - `information_schema` will show 67 tables because `schema_version` is included.
    - The FK total is not stated. By my count it is 143 + about 15 = 158:

      | Source | New FKs |
      |---|---|
      | `device.revoked_by` | 1 |
      | `sync_item` | 3 |
      | `notification` | about 3 |
      | `unmet_request` | 5 |
      | `pet.limit_override_by` | 1 |
      | `lookup_value.species_id` | 1 |
      | `audit_log.import_batch_id` | 1 |

    - `SHOW WARNINGS` only reflects the last statement.
    - Correction: pin 66 ERD tables + `schema_version`, pin the exact FK number, and check warnings after every statement.

18. **Smaller schema issues:**
    - `distribution.sync_exception VARCHAR(40)` (0004) holds only one reason, but a replay can fail several checks. Use `SET(...)`, which the schema already uses at L165.
    - Ledger "lock rows then upsert": `SELECT … FOR UPDATE` on a missing `site_stock` row takes gap locks under REPEATABLE READ, so parallel first inserts deadlock. Pre-create `site_stock` rows at qty 0 when a product or site is created.
    - The erasure list (P6) omits the new PII tables `outbound_message` (recipient, body) and `notification.message`.
    - 520 is PAD SPACE, whereas 0900 is NO PAD. Trailing-space variants collide in UNIQUE keys (username, email, barcode PK, `participant_code`). Trim in the Validator and note it in the changes doc.
    - NOT NULL columns some flows can't fill:
      - `distribution.event_id` NOT NULL FK (L461/L991): an offline replay needs a server-side event. The pack must carry the day's Scheduled events, with a path when none exists.
      - `registration_draft.applicant_name` NOT NULL (L270) conflicts with US-08 "saved without validation".
      - `pet.size_band_id` (L385) and `participant.household_size` (L162) must be required or derived in offline, import and self-service flows.
      - `participant.registered_by` is fine.

**Confirmed**

- **Counts:** 59 CREATE TABLE, 143 FKs and 59 DROP TABLE lines. There are 60 `0900_ai_ci` occurrences: 59 tables plus the header comment at L4. 59 + 7 = 66 holds if `schema_version` is excluded.
- **Referenced items all exist:** `distribution.local_date` L468, `pet.last_confirmed_present` L401, `participant.surname_phonetic` L151, the 5 `event_check_in.outcome` values L451, the 6 `snv_referral` statuses L668, `clinic.portal_access_hash` L631, `auth_method 'Offline'` L88. The device columns, new `end_reason` values, `import_batch` 'Queued'/'Running' and 'Offline Grant' are correctly listed as migrations.
- **Collation:** `utf8mb4_unicode_520_ci` exists on both engines. The Percona 8.4.6 prod dump uses it 13 times (`sql/foodpantrydb.sql`) and the MariaDB 10.4.32 dumps use it too. As UCA 5.2 `_ci` it is accent-insensitive, so US-06 holds.
- **Portable features:** banning SKIP LOCKED is right (MariaDB only has it from 10.6). Appending ENUM values, nullable `voucher_number` with UNIQUE (multiple NULLs), DATETIME(3), SET, STORED generated column with UNIQUE, and MariaDB JSON as LONGTEXT `utf8mb4_bin` with a CHECK all work on both. Moving `pet.status` above `active_microchip` is safe on both.
- **`id_sequence`:** `SELECT … FOR UPDATE` then `UPDATE` inside the transaction is gapless on rollback and behaves the same on both InnoDB engines. UC-03 does require "no Participant ID has been consumed" on failure. All new FKs point at primary keys, so they are fine under MySQL 8.4's `restrict_fk_on_non_standard_key`.
