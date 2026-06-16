-- Backfill audit_log.vendor for filter list entries
--
-- AuditRecord::filterListEntryAdded()/filterListEntryDeleted() now derive and store the
-- vendor (the segment before "/" in the package name) so these records are reachable via
-- the transparency-log vendor filter, which matches the indexed audit_log.vendor column.
--
-- Rows written before that change have vendor = NULL. The package name lives in the JSON
-- attributes at $.entry.package_name. SUBSTRING_INDEX(name, '/', 1) mirrors the PHP
-- getVendorFromPackage() (Preg::replace('{/.*$}', '', $name)): for "vendor/pkg" it yields
-- "vendor", and for a name with no slash it returns the whole string. MySQL's default
-- case-insensitive collation matches the equality used by VendorFilter.

UPDATE audit_log
    SET vendor = SUBSTRING_INDEX(JSON_UNQUOTE(JSON_EXTRACT(attributes, '$.entry.package_name')), '/', 1)
    WHERE type IN ('filter_list_entry_added', 'filter_list_entry_deleted')
      AND vendor IS NULL
      AND JSON_EXTRACT(attributes, '$.entry.package_name') IS NOT NULL;
