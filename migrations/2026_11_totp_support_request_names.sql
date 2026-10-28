-- Rename the persisted authenticator recovery request type.
-- These requests reset a TOTP authenticator app.

UPDATE support_request
SET type = 'lost_totp'
WHERE type = 'lost_2fa';
