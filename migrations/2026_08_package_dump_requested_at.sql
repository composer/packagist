-- Run BEFORE deploying the code: purely additive, so the currently-deployed code keeps working while
-- the new code needs both the column and the index to exist from the moment it starts. The matching
-- drops live in 2026_08b_drop_dead_dump_columns.sql, which must run AFTER the deploy.
--
-- Marking a package for re-dump used to null dumpedAtV2, which is destructive: V2Dumper writes that
-- once at the end of a run, so a mark landing mid-run was silently overwritten and never dumped.
-- dumpRequestedAt records the request instead and staleness compares the two, so it cannot be lost.
--
-- Two statements on purpose: on its own the ADD COLUMN can run ALGORITHM=INSTANT, whereas folding it
-- into the index DDL drags it down to an INPLACE rebuild of the whole package table.
ALTER TABLE package ADD dumpRequestedAt DATETIME DEFAULT NULL;
ALTER TABLE package ADD INDEX dumped2_requested_crawled_frozen_idx (dumpedAtV2, dumpRequestedAt, crawledAt, frozen);
