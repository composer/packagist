-- Version immutability + soft-delete reasons
--
-- Adds:
--   deletionReason         enum-string column (auto_missing | maintainer | admin)
--   deletionReasonText     optional human-readable reason for admin takedowns
--   lastBlockedReference   last attempted source/dist ref that was refused on a stable version
--   index over (softDeletedAt, deletionReason) to keep the purge sweep fast
--
-- The UPDATE seeds existing softDeletedAt rows with the auto_missing reason so the
-- invariant "softDeletedAt IS NOT NULL ⇔ deletionReason IS NOT NULL" holds everywhere.

ALTER TABLE package_version
    ADD COLUMN deletionReason VARCHAR(32) NULL AFTER softDeletedAt,
    ADD COLUMN deletionReasonText TEXT NULL AFTER deletionReason,
    ADD COLUMN lastBlockedReference VARCHAR(255) NULL AFTER deletionReasonText,
    ADD INDEX softdel_reason_idx (softDeletedAt, deletionReason);

UPDATE package_version
    SET deletionReason = 'auto_missing'
    WHERE softDeletedAt IS NOT NULL;
