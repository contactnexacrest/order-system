-- NexaCrest: which migrations does this database still need?
-- READ-ONLY: it only looks; it changes nothing and shows no business data.
-- How: phpMyAdmin > click your database > SQL tab > paste all of this > Go.
-- Then send a screenshot of the result table.
--
-- Fresh install of 14 Nov 2026 (owner decision D-372): the live database was installed from
-- schema.sql + seed.sql, which already hold every earlier change, so every older migration file
-- (2026-11-11a ... 2026-11-13g) was removed from docs/migrations (git history keeps them).
-- From now on every new file in docs/migrations gets ONE row below, in the same commit
-- (tests MigrationCheckScriptCoverageTest / migrationCheckScriptCoverage.test.js enforce it).
-- How to add a row: copy the example under the first SELECT, take out the "-- ", and put in the
-- file's id (e.g. 2026-12-01a), what it does, and a check that is 1 once the file has run
-- (a new column, a new table, or a new setting row). Plain SELECTs only (no variables, no PREPARE).
SELECT q.migration AS `Migration`, q.what AS `What it does`, q.status AS `Status`
FROM (
  SELECT '(none yet)' AS migration,
         'No migration since the fresh install of 14 Nov 2026 (D-372)' AS what,
         'nothing to run' AS status
  -- UNION ALL
  -- SELECT '2026-12-01a', 'Example: new column orders.example_col',
  --        IF((SELECT COUNT(*) FROM information_schema.columns
  --            WHERE table_schema = DATABASE() AND table_name = 'orders' AND column_name = 'example_col') = 1,
  --           'already done', 'NEEDED')
) q;
