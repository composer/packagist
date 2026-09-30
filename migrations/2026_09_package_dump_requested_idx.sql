-- The v2 dump staleness select runs every ~2s per worker and both of its clauses compare two
-- columns, which no index can seek - it was scanning 468,848 rows a call for 12,306s of DB time in
-- 16h, essentially all of package's 49.4 billion reads. Bounding it by a Redis watermark turns the
-- clauses into ranges, and the range on dumpRequestedAt needs a key it can lead.
--
-- dumped2_requested_crawled_frozen_idx has dumpRequestedAt in second position behind dumpedAtV2, so
-- it cannot serve that range on its own; crawled_idx already covers the crawledAt clause, and
-- dumpedAtV2 IS NULL keeps using the existing composite.
--
-- Cheap despite package being large: only markForDump() writes dumpRequestedAt, and the hot
-- `UPDATE package SET dumpedAtV2` the dumper runs does not touch this column, so it never maintains
-- this index.
ALTER TABLE package
    ADD INDEX dump_requested_idx (dumpRequestedAt),
    ALGORITHM=INPLACE, LOCK=NONE;
