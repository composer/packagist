-- Audit log name-based search index
--
-- The transparency-log filters (user / actor / package) searched usernames and package names
-- inside the JSON `attributes` blob (JSON_EXTRACT / JSON_CONTAINS), which is an un-indexed full
-- table scan of audit_log — the `?user=<name>` link on user profile pages timed out / crashed.
--
-- audit_log_search denormalizes every name a record references at write time into indexed
-- (type, name) -> auditLogId rows, so those filters become a simple indexed lookup. Names are
-- stored lowercased (search is case-insensitive; usernames are canonically lowercased anyway).
-- The (type, name, auditLogId) primary key doubles as the lookup index; auditLogId is a
-- time-sortable ULID so a (type, name) range already yields rows in the id-DESC order the
-- transparency log paginates by. Rows are never deleted — the transparency log is permanent.
--
-- Schema for dev/test is created from the ORM metadata (App\Entity\AuditLogSearch and the new
-- indexes on App\Entity\AuditRecord); this file applies the same change to production.
--
-- Backfill existing rows after applying this with: bin/console packagist:populate:audit-log-search

CREATE TABLE audit_log_search (
    type VARCHAR(16) NOT NULL,
    name VARCHAR(255) NOT NULL,
    auditLogId BINARY(16) NOT NULL,
    INDEX audit_log_id_idx (auditLogId),
    PRIMARY KEY (type, name, auditLogId)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`;

ALTER TABLE audit_log
    DROP INDEX package_idx;
