-- Filter list entry admin controls (enable/disable, version overwrite, internal notes)
--
-- Adds the columns backing the new admin UI on filter_list_entry:
--   disabled          soft on/off toggle so an entry can be suspended without deleting it
--   overwriteVersion  admin-supplied constraint that overrides the upstream-reported one
--                     (the upstream identity always stays in `version`, so the resolver still
--                      recognises the entry across syncs regardless of the override)
--   internalNote      free-form admin note, not shown publicly
--
-- `list` and `source` are also shrunk from the default VARCHAR(255) to VARCHAR(32): the new
-- UNIQUE index spans four columns and the full-width version would exceed the 3072-byte key
-- limit (ERROR 1071). Both are enum-backed, so 32 chars is ample. `source` is promoted into
-- the unique key so the same (list, packageName, version) can exist for different sources.

ALTER TABLE filter_list_entry
    ADD COLUMN disabled TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN overwriteVersion VARCHAR(255) DEFAULT NULL,
    ADD COLUMN internalNote LONGTEXT DEFAULT NULL;

ALTER TABLE filter_list_entry
    MODIFY `list` VARCHAR(32) NOT NULL,
    MODIFY `source` VARCHAR(32) NOT NULL,
    DROP INDEX list_package_version_idx,
    ADD UNIQUE INDEX list_package_version_source_idx (`list`, `packageName`, `version`, `source`);
