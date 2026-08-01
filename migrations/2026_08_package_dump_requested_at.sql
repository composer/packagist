-- Marking a package for re-dump used to null dumpedAtV2, which is destructive: V2Dumper writes
-- dumpedAtV2 once at the end of a run, so a mark landing between a package's hydration and that write
-- was silently overwritten and the change never dumped. The crawledAt clause masked this for the
-- Updater, but not for the advisory / filter-list listeners, which never touch crawledAt.
-- dumpRequestedAt records the request instead, and staleness compares the two, so a mark can no
-- longer be lost. See metadata-dump-followups.md.
ALTER TABLE package
  ADD dumpRequestedAt DATETIME DEFAULT NULL,
  ADD INDEX dumped2_requested_crawled_frozen_idx (dumpedAtV2, dumpRequestedAt, crawledAt, frozen),
  DROP INDEX dumped2_crawled_frozen_idx;
