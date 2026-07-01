-- Backfill audit_log attributes.name for filter list entries
--
-- Filter list records nest the package name under attributes.$.entry.package_name, while
-- every other audit record type stores it at the top-level attributes.$.name. The
-- transparency-log package filter (PackageNameFilter) matches $.name, so filter list
-- records were unreachable by package-name search.
--
-- AuditRecord::filterListEntryAdded()/filterListEntryDeleted() now also write a top-level
-- $.name (a copy of entry.package_name; the entry snapshot is left intact). This copies
-- that value onto existing rows. The $.name IS NULL guard keeps it idempotent and avoids
-- touching rows already written by the new code path.

UPDATE audit_log
    SET attributes = JSON_SET(attributes, '$.name', JSON_UNQUOTE(JSON_EXTRACT(attributes, '$.entry.package_name')))
    WHERE type IN ('filter_list_entry_added', 'filter_list_entry_deleted')
      AND JSON_EXTRACT(attributes, '$.entry.package_name') IS NOT NULL
      AND JSON_EXTRACT(attributes, '$.name') IS NULL;
