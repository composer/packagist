-- Swaps all_deps (package_id, packageName) for the same two columns the other way round.
--
-- all_deps was a strict prefix of the primary key (package_id, packageName, type) on a table whose
-- every column is in that key, so nothing could ever prefer it: performance_schema recorded 0 reads
-- over 155 days, against by_type's 64G and PRIMARY's 43M.
--
-- (packageName, package_id) is what the unsorted dependents listing iterates. InnoDB appends the
-- rest of the PK, so it is physically (packageName, package_id, type): covering, and ordered by
-- package_id for a given name whatever the type. That makes "the dependents of x, from id y on" an
-- index range scan that stops at the limit, with duplicates - a package requiring the same name in
-- both require and require-dev - adjacent and so collapsible by DISTINCT. by_type cannot do this
-- for requires=all, since it orders by (type, package_id) and would hand back each such package
-- twice, potentially hundreds of pages apart.
--
-- Same index count and roughly the same ~100MB as what it replaces, so the write cost on this
-- table - which every crawl deletes and reinserts wholesale - does not move.
--
-- Deliberately keeping IDX_BB9077A4F44CABFF (package_id), redundant by the same rule as all_deps
-- but with 66M reads: the clustered index carries DB_TRX_ID and DB_ROLL_PTR per row, making it
-- 152MB against that index's 100MB, so it is the cheaper covering copy for the by-package_id delete.
ALTER TABLE dependent
    DROP INDEX all_deps,
    ADD INDEX by_name_package (packageName, package_id),
    ALGORITHM=INPLACE, LOCK=NONE;
