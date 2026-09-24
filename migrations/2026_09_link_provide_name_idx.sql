-- findProviders() - the /providers/{name} page - filters link_provide on packageName, which had no
-- index, so every call scanned the whole table: 79,386 rows examined per call over 57,174 calls in
-- 16h, i.e. 4.47 billion of link_provide's 4.59 billion reads came from this one query.
--
-- The table is tiny (~78k rows) and effectively read-only - 173 writes against 4.59 billion reads in
-- the same window - so the index costs nothing, unlike a new key on a write-heavy table.
ALTER TABLE link_provide
    ADD INDEX link_provide_name_idx (packageName),
    ALGORITHM=INPLACE, LOCK=NONE;
