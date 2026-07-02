-- Index audit_log.organizationId so the per-organization audit log page
-- (organization_audit_log) can filter records for a single organization
-- without scanning the whole table.

ALTER TABLE audit_log ADD INDEX organization_idx (organizationId);
