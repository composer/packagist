-- The v1 metadata dumper was removed; package.dumpedAt is dead. Nothing writes it a non-null value
-- anymore (every setDumpedAt() call only ever nulled it), and its only readers --
-- PackageRepository::getStalePackagesForDumping() and getPackageNamesUpdatedSince() -- had no callers.
-- v2 dump staleness is driven by dumpedAtV2 (dumped2_idx / dumped2_crawled_frozen_idx).
-- Drop the column and its now-unused single-column index.
ALTER TABLE package DROP INDEX dumped_idx, DROP COLUMN dumpedAt;
