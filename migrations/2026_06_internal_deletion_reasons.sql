-- Internal (admin-only) deletion reasons
--
-- Adds:
--   internalDeletionReasonText   admin/auditor-only free-text reason, stored alongside the existing
--                                public deletionReasonText, for when an admin soft-deletes or hides
--                                a version and wants to record private notes (which may contain PII).
--
-- It is never shown publicly: the transparency log only surfaces it to ROLE_AUDITOR, and it is
-- excluded from all API/metadata serialization (soft-deleted versions are not dumped at all).
--
-- Package deletions carry their public + internal reasons in the audit_log JSON attributes, so no
-- column is needed for those.

ALTER TABLE package_version
    ADD COLUMN internalDeletionReasonText TEXT NULL AFTER deletionReasonText;
