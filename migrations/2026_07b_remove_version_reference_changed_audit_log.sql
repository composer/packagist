-- Remove the VersionReferenceChanged audit records
--
-- The version_reference_changed audit type is being removed. It only recorded a filtered
-- subset of reference changes (dev versions were logged only when other metadata changed too,
-- not on every commit), so it never formed a complete or consistent audit trail. Recording
-- every commit instead would be far too much data to keep, so the type is more noise than
-- signal and is dropped. The producing event/listener chain is deleted in the same change,
-- so no new rows will be written.
--
-- audit_log_search denormalizes each record's searchable names (here the package name, indexed
-- as a Package term) and has no FK to audit_log, so its rows for these records must be deleted
-- explicitly. Delete from the index first (it resolves ids via audit_log), then the records.

DELETE s FROM audit_log_search s
    INNER JOIN audit_log a ON a.id = s.auditLogId
    WHERE a.type = 'version_reference_changed';

DELETE FROM audit_log WHERE type = 'version_reference_changed';
