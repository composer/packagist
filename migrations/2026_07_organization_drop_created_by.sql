-- Ownership is now derived from membership of the `owners` team and who
-- created the org is preserved in the audit log

ALTER TABLE organization DROP FOREIGN KEY FK_organization_created_by;
ALTER TABLE organization DROP INDEX org_created_by_idx;
ALTER TABLE organization DROP COLUMN createdBy;
