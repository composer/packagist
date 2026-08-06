-- Run AFTER the code is deployed: everything dropped here is still referenced by the previously
-- deployed code. It reads and nulls package.dumpedAt, and its stale-package query names
-- dumped2_crawled_frozen_idx in a USE INDEX hint, which errors out the moment the index is gone.
-- The additive half runs before the deploy, see 2026_08_package_dump_requested_at.sql.
--
-- The v1 metadata dumper was removed, so package.dumpedAt is dead: nothing wrote it a non-null value
-- and both of its readers had no callers. v2 staleness runs off dumpedAtV2 / dumpRequestedAt through
-- dumped2_requested_crawled_frozen_idx, which supersedes dumped2_crawled_frozen_idx and (as dumpedAtV2
-- leads it) the single-column dumped2_idx.
ALTER TABLE package DROP INDEX dumped_idx, DROP COLUMN dumpedAt, DROP INDEX dumped2_crawled_frozen_idx, DROP INDEX dumped2_idx;
