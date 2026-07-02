-- Backfill audit_log attributes.entry.public_id for filter list entries
--
-- The new filter list edit view lists the audit records that belong to an entry by matching on
-- attributes.$.entry.public_id. Older filter_list_entry_added / filter_list_entry_deleted records
-- were written before publicId was tracked, so they carry no public_id and would not appear.
--
-- This copies each entry's current publicId onto its historical records, matching on the
-- package name / list / version snapshot stored in the audit attributes. The public_id IS NULL
-- guard keeps it idempotent and skips rows already written by the new code path.
--
-- Run AFTER deploying the new code (it relies on filter_list_entry.publicId being populated).

UPDATE audit_log a
    INNER JOIN filter_list_entry fle
        ON fle.packageName = JSON_UNQUOTE(JSON_EXTRACT(a.attributes, '$.entry.package_name'))
       AND fle.list       = JSON_UNQUOTE(JSON_EXTRACT(a.attributes, '$.entry.list'))
       AND fle.version    = JSON_UNQUOTE(JSON_EXTRACT(a.attributes, '$.entry.version'))
    SET a.attributes = JSON_SET(a.attributes, '$.entry.public_id', fle.publicId)
    WHERE a.type IN ('filter_list_entry_added', 'filter_list_entry_deleted')
      AND JSON_EXTRACT(a.attributes, '$.entry.public_id') IS NULL;
