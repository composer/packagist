-- Denormalized freeze timestamp so the admin "Frozen users" review queue can order/paginate cheaply
-- (WHERE frozen = :reason ORDER BY frozenAt DESC) instead of scanning fos_user and correlating the
-- audit log per row. The UserFrozen audit log stays canonical for who/why.

ALTER TABLE fos_user ADD frozenAt DATETIME DEFAULT NULL AFTER frozen;

-- Backfill existing frozen accounts from their latest UserFrozen audit record; accounts frozen
-- without an audit trail (e.g. the ROLE_SPAMMER migration) fall back to a clearly-historical
-- 2026-01-01 marker so no frozen account is left with a NULL frozenAt.
UPDATE fos_user u
SET u.frozenAt = COALESCE(
    (SELECT MAX(a.datetime) FROM audit_log a WHERE a.userId = u.id AND a.type = 'user_frozen'),
    '2026-01-01 00:00:00'
)
WHERE u.frozen IS NOT NULL;

-- Composite index serving `WHERE frozen = :reason ORDER BY frozenAt DESC` (the default queue view).
CREATE INDEX user_frozen_idx ON fos_user (frozen, frozenAt);
