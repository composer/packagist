-- Rename historical authenticator app events to match the TOTP audit types.
-- The old event types were written only when a TOTP authenticator was enabled or removed.

UPDATE audit_log
SET type = CASE type
    WHEN 'two_fa_activated' THEN 'totp_activated'
    WHEN 'two_fa_deactivated' THEN 'totp_deactivated'
    ELSE type
END
WHERE type IN ('two_fa_activated', 'two_fa_deactivated');
