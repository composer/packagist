-- githubId had no index at all, so findOneBy(['githubId' => ...]) - the lookup that runs on every
-- GitHub login - full-scanned the table: 875,663 rows examined per call, on every call, per the
-- prod digests.
--
-- Plain rather than UNIQUE: one GitHub account should map to one user, but a historical duplicate
-- would fail this migration on a live table, and nothing in the login path relies on the constraint.
--
-- fos_user takes 14,015 writes against 5.3 billion reads, so the write amplification a new
-- secondary index costs is noise here - unlike on audit_log, where the same reasoning is why the
-- support risk panel was routed through audit_log_search instead of getting a (userId, type) index.
ALTER TABLE fos_user
    ADD INDEX user_github_id_idx (githubId),
    ALGORITHM=INPLACE, LOCK=NONE;
